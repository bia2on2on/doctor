<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Infrastructure\Audit\HashChain;
use PHPUnit\Framework\TestCase;

/**
 * TP-11 (منطق) — صحت‌سنجی زنجیر هش Audit + تشخیص جعل (ADR-0008).
 */
final class HashChainTest extends TestCase
{
    private function makeRow(array $fields, string $prevHash): array
    {
        $row = array_merge($fields, [
            'prev_hash' => $prevHash,
        ]);
        $row['row_hash'] = HashChain::computeRowHash($prevHash, HashChain::fieldsFor($row));

        return $row;
    }

    public function testChainVerifies(): void
    {
        $prev = HashChain::GENESIS;
        $rows = [];
        for ($i = 1; $i <= 5; $i++) {
            $rows[] = $this->makeRow([
                'action' => 'TEST_' . $i,
                'created_at' => '2026-09-05 10:00:0' . $i . '.000',
                'actor_role' => 'system',
            ], $prev);
            $prev = $rows[$i - 1]['row_hash'];
        }

        $result = HashChain::verify($rows);
        $this->assertTrue($result['ok']);
        $this->assertSame(5, $result['checked']);
    }

    public function testTamperedRowDetected(): void
    {
        $prev = HashChain::GENESIS;
        $rows = [];
        for ($i = 1; $i <= 5; $i++) {
            $rows[] = $this->makeRow(['action' => 'TEST_' . $i], $prev);
            $prev = $rows[$i - 1]['row_hash'];
        }

        // جعل ردیف 3: تغییر action بعد از ساخت هش
        $rows[2]['action'] = 'TAMPERED';

        $result = HashChain::verify($rows);
        $this->assertFalse($result['ok']);
        $this->assertSame(3, $result['broken_at']);
    }

    public function testDeletedRowBreaksChain(): void
    {
        $prev = HashChain::GENESIS;
        $rows = [];
        for ($i = 1; $i <= 5; $i++) {
            $rows[] = $this->makeRow(['action' => 'TEST_' . $i], $prev);
            $prev = $rows[$i - 1]['row_hash'];
        }

        // حذف ردیف 2 → prev_hash ردیف 3 دیگر با row_hash ردیف 1 نمی‌خواند
        array_splice($rows, 1, 1);

        $result = HashChain::verify($rows);
        $this->assertFalse($result['ok']);
        $this->assertSame(2, $result['broken_at']);
    }

    public function testHashIsDeterministic(): void
    {
        $a = HashChain::computeRowHash(HashChain::GENESIS, ['x' => 1, 'y' => 'b']);
        $b = HashChain::computeRowHash(HashChain::GENESIS, ['y' => 'b', 'x' => 1]);

        $this->assertSame($a, $b, 'canonical باید مستقل از ترتیب کلیدها باشد');
    }

    public function testDifferentPrevHashDifferentRowHash(): void
    {
        $a = HashChain::computeRowHash(HashChain::GENESIS, ['x' => 1]);
        $b = HashChain::computeRowHash(str_repeat('a', 64), ['x' => 1]);

        $this->assertNotSame($a, $b);
    }

    public function testEmptyChainIsOk(): void
    {
        $result = HashChain::verify([]);
        $this->assertTrue($result['ok']);
        $this->assertSame(0, $result['checked']);
    }
}

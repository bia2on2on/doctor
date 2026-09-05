<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Infrastructure\Queue\JobQueue;
use PHPUnit\Framework\TestCase;

/**
 * TP-13 (بخش Backoff) — رفتار Retry Jobها.
 */
final class JobQueueBackoffTest extends TestCase
{
    public function testBackoffSchedule(): void
    {
        $this->assertSame(1, JobQueue::backoffSeconds(1));
        $this->assertSame(5, JobQueue::backoffSeconds(2));
        $this->assertSame(15, JobQueue::backoffSeconds(3));
        $this->assertSame(60, JobQueue::backoffSeconds(4));
        $this->assertSame(300, JobQueue::backoffSeconds(5));
        $this->assertSame(300, JobQueue::backoffSeconds(99), 'بعد از جدول → ثابت 300');
    }

    public function testBackoffNeverNegative(): void
    {
        $this->assertGreaterThan(0, JobQueue::backoffSeconds(0));
        $this->assertGreaterThan(0, JobQueue::backoffSeconds(-5));
    }
}

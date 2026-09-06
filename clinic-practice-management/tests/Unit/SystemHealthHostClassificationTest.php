<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Application\System\SystemHealthService;
use PHPUnit\Framework\TestCase;

/**
 * F10 — دسته‌بندی Host Capability (spec §10–§13):
 * SUPPORTED / SUPPORTED_WITH_WARNINGS / UNSUPPORTED.
 */
final class SystemHealthHostClassificationTest extends TestCase
{
    /**
     * @return array{key:string,label:string,status:string,detail:string}
     */
    private function row(string $key, string $status): array
    {
        return ['key' => $key, 'label' => $key, 'status' => $status, 'detail' => ''];
    }

    public function testAllPassIsSupported(): void
    {
        $checks = [
            $this->row('php.version', SystemHealthService::PASS),
            $this->row('db.reachable', SystemHealthService::PASS),
            $this->row('db.migrated', SystemHealthService::PASS),
            $this->row('storage.files', SystemHealthService::PASS),
            $this->row('storage.backups', SystemHealthService::PASS),
            $this->row('cron.jobs', SystemHealthService::PASS),
            $this->row('license.state', SystemHealthService::PASS), // غیرحیاتی
        ];
        $host = SystemHealthService::classifyHost($checks);
        $this->assertSame(SystemHealthService::HOST_SUPPORTED, $host['status']);
        $this->assertSame([], $host['issues']);
    }

    public function testOnlyWarningsIsSupportedWithWarnings(): void
    {
        $checks = [
            $this->row('php.version', SystemHealthService::PASS),
            $this->row('db.reachable', SystemHealthService::PASS),
            $this->row('db.migrated', SystemHealthService::PASS),
            $this->row('storage.files', SystemHealthService::PASS),
            $this->row('storage.backups', SystemHealthService::PASS),
            $this->row('cron.jobs', SystemHealthService::WARNING), // WP-Cron fallback
        ];
        $host = SystemHealthService::classifyHost($checks);
        $this->assertSame(SystemHealthService::HOST_SUPPORTED_WITH_WARNINGS, $host['status']);
        $this->assertContains('cron.jobs', $host['issues']);
    }

    public function testCriticalFailIsUnsupported(): void
    {
        $checks = [
            $this->row('php.version', SystemHealthService::PASS),
            $this->row('db.reachable', SystemHealthService::FAIL),
            $this->row('cron.jobs', SystemHealthService::PASS),
        ];
        $host = SystemHealthService::classifyHost($checks);
        $this->assertSame(SystemHealthService::HOST_UNSUPPORTED, $host['status']);
        $this->assertCount(1, $host['issues']);
    }

    public function testNonCriticalFailDoesNotMakeUnsupported(): void
    {
        $checks = [
            $this->row('php.version', SystemHealthService::PASS),
            $this->row('db.reachable', SystemHealthService::PASS),
            $this->row('cron.jobs', SystemHealthService::PASS),
            $this->row('license.state', SystemHealthService::FAIL), // غیرحیاتی
        ];
        $host = SystemHealthService::classifyHost($checks);
        $this->assertSame(SystemHealthService::HOST_SUPPORTED, $host['status']);
    }
}

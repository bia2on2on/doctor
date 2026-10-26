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

    /**
     * D5/D6 — مدرکِ نامشخص **هرگز** «پشتیبانی‌شده» نمی‌شود، حتی اگر متنش
     * MariaDB را ذکر کند.
     *
     * پیش از این یک استثنای پنهان در `classifyHost()` هر ردیفِ
     * `db.mysql_version` با وضعیت UNKNOWN را (اگر متنش MariaDB داشت) به
     * «هشدار» تنزل می‌داد؛ نتیجه: میزبان با مدرکِ نامشخص، `SUPPORTED_WITH_WARNINGS`
     * می‌شد در حالی که دروازهٔ مهاجرت همان مدرک را رد می‌کند.
     */
    public function testUnknownEvidenceIsNeverSupportedEvenWhenItMentionsMariaDb(): void
    {
        foreach (
            [
                'MariaDB شناسایی شد — کفِ اعلام‌شده فقط MySQL 8.0 است',
                'mariadb:detected | MariaDB شناسایی شد',
                'mariadb:unknown_version | MariaDB',
            ] as $detail
        ) {
            $checks = [
                $this->row('php.version', SystemHealthService::PASS),
                $this->row('wp.version', SystemHealthService::PASS),
                $this->row('db.reachable', SystemHealthService::PASS),
                $this->row('db.migrated', SystemHealthService::PASS),
                ['key' => 'db.mysql_version', 'label' => 'db.mysql_version', 'status' => SystemHealthService::UNKNOWN, 'detail' => $detail],
                $this->row('storage.files', SystemHealthService::PASS),
                $this->row('storage.backups', SystemHealthService::PASS),
                $this->row('cron.jobs', SystemHealthService::PASS),
            ];
            $host = SystemHealthService::classifyHost($checks);

            $this->assertSame(SystemHealthService::HOST_UNSUPPORTED, $host['status'], $detail);
            $this->assertNotEmpty(
                array_filter($host['issues'], static fn (string $i): bool => 0 === strpos($i, 'db.mysql_version:')),
                $detail
            );
        }
    }

    public function testGenuineUnknownNonMariaDbDatabaseStillMakesHostUnsupported(): void
    {
        $checks = [
            $this->row('php.version', SystemHealthService::PASS),
            $this->row('wp.version', SystemHealthService::PASS),
            $this->row('db.reachable', SystemHealthService::PASS),
            $this->row('db.migrated', SystemHealthService::PASS),
            ['key' => 'db.mysql_version', 'label' => 'db.mysql_version', 'status' => SystemHealthService::UNKNOWN, 'detail' => 'نسخهٔ سرور دیتابیس در دسترس نیست'],
            $this->row('storage.files', SystemHealthService::PASS),
            $this->row('storage.backups', SystemHealthService::PASS),
            $this->row('cron.jobs', SystemHealthService::PASS),
        ];
        $host = SystemHealthService::classifyHost($checks);
        $this->assertSame(SystemHealthService::HOST_UNSUPPORTED, $host['status']);
    }

    public function testGenuineCriticalFailuresRemainUnsupported(): void
    {
        foreach (['php.version', 'wp.version', 'db.reachable', 'db.migrated', 'storage.files', 'storage.backups', 'cron.jobs'] as $key) {
            $checks = [
                $this->row('php.version', SystemHealthService::PASS),
                $this->row('wp.version', SystemHealthService::PASS),
                $this->row('db.reachable', SystemHealthService::PASS),
                $this->row('db.migrated', SystemHealthService::PASS),
                $this->row('storage.files', SystemHealthService::PASS),
                $this->row('storage.backups', SystemHealthService::PASS),
                $this->row('cron.jobs', SystemHealthService::PASS),
            ];
            foreach ($checks as $i => $c) {
                if ($c['key'] === $key) {
                    $checks[$i]['status'] = SystemHealthService::FAIL;
                }
            }
            $host = SystemHealthService::classifyHost($checks);
            $this->assertSame(SystemHealthService::HOST_UNSUPPORTED, $host['status'], $key);
        }
    }


    public function testMariaDbUncertifiedCoexistsWithGenuineCriticalFailure(): void
    {
        $checks = [
            $this->row('php.version', SystemHealthService::FAIL),
            $this->row('wp.version', SystemHealthService::PASS),
            $this->row('db.reachable', SystemHealthService::PASS),
            $this->row('db.migrated', SystemHealthService::PASS),
            ['key' => 'db.mysql_version', 'label' => 'db.mysql_version', 'status' => SystemHealthService::UNKNOWN, 'detail' => 'mariadb:detected | MariaDB شناسایی شد'],
            $this->row('storage.files', SystemHealthService::PASS),
            $this->row('storage.backups', SystemHealthService::PASS),
            $this->row('cron.jobs', SystemHealthService::PASS),
        ];
        $host = SystemHealthService::classifyHost($checks);
        $this->assertSame(SystemHealthService::HOST_UNSUPPORTED, $host['status']);
        $this->assertStringContainsString('php.version', implode(' | ', $host['issues']));
        // D — مدرکِ نامشخصِ دیتابیس هم مستقل از خطای دیگر باید در issues باشد
        // (پیش از این به‌خاطرِ استثنای MariaDB از issues حذف می‌شد).
        $this->assertStringContainsString('db.mysql_version', implode(' | ', $host['issues']));
    }

    public function testStructuredMariaDbIdentifierIsDetected(): void
    {
        $this->assertTrue(SystemHealthService::isMariaDbCompatibilityDetail('mariadb:detected | MariaDB شناسایی شد'));
        $this->assertFalse(SystemHealthService::isMariaDbCompatibilityDetail('نسخهٔ سرور دیتابیس در دسترس نیست'));
    }

}

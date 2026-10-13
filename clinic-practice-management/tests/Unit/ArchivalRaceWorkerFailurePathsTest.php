<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Tests\Support\ArchivalRaceWorker;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/Support/ArchivalRaceWorker.php';

/**
 * Failure paths of the independent archival-race worker launcher.
 *
 * The archival race suite depends on a worker process that must never be able to
 * touch the parent's MySQL session. That isolation is only trustworthy if every
 * failure path is loud: a worker that cannot start, or that dies before writing
 * its outcome, must never be read as success. These tests exercise the launcher
 * against inputs that cannot reach a database at all, so they run in the Unit
 * suite and touch no shared state.
 */
final class ArchivalRaceWorkerFailurePathsTest extends TestCase
{
    private const MISSING_CONFIG = '/nonexistent-cpms-fixture/wp-tests-config.php';

    protected function setUp(): void
    {
        parent::setUp();
        if (!function_exists('proc_open') || !ArchivalRaceWorker::available()) {
            self::markTestSkipped('proc_open is required to launch the independent worker');
        }
    }

    /**
     * A worker whose WordPress configuration is missing must report a precise
     * protocol error and exit non-zero — never a silent or empty success.
     */
    public function testWorkerBootstrapFailureIsReportedAndExitsNonZero(): void
    {
        $worker = ArchivalRaceWorker::launch($this->task([
            'WP_TESTS_CONFIG_FILE_PATH' => self::MISSING_CONFIG,
        ]));

        self::assertGreaterThan(0, $worker->pid(), 'the worker must be a real process');
        self::assertNotSame(getmypid(), $worker->pid(), 'the worker must never be this process');

        $line = $worker->nextLine(20.0);
        self::assertIsString($line, 'a failing worker must still speak the protocol: ' . $worker->diagnostics());
        self::assertStringStartsWith('ERROR:', $line);

        self::assertSame(1, $worker->waitForExit(20.0), 'a failed bootstrap must exit non-zero');
        self::assertNotSame('', (string) $worker->outcome(), 'the failure payload must be captured');
        self::assertStringStartsWith('RuntimeException:', (string) $worker->outcome());
        $worker->terminate();
    }

    /**
     * A configuration that exists but defines no WordPress core location must be
     * rejected as well: the worker may never fall back to guessing a bootstrap.
     */
    public function testWorkerRejectsConfigurationWithoutCore(): void
    {
        $config = tempnam(sys_get_temp_dir(), 'cpms-race-config-');
        self::assertIsString($config);
        file_put_contents($config, "<?php\n// deliberately empty configuration\n");

        try {
            $worker = ArchivalRaceWorker::launch($this->task([
                'WP_TESTS_CONFIG_FILE_PATH' => $config,
                'WP_CORE_DIR' => '/nonexistent-cpms-core',
            ]));

            $line = $worker->nextLine(20.0);
            self::assertIsString($line, 'expected a protocol error: ' . $worker->diagnostics());
            self::assertStringStartsWith('ERROR:', $line);
            self::assertStringContainsString('core directory', $line);
            self::assertSame(1, $worker->waitForExit(20.0));
            $worker->terminate();
        } finally {
            @unlink($config);
        }
    }

    /**
     * Termination is bounded and reaps the process: no orphaned worker may
     * outlive a failing test.
     */
    public function testTerminationIsBoundedAndLeavesNoRunningProcess(): void
    {
        $worker = ArchivalRaceWorker::launch($this->task([
            'WP_TESTS_CONFIG_FILE_PATH' => self::MISSING_CONFIG,
        ]));

        $started = microtime(true);
        $worker->terminate();
        self::assertLessThan(10.0, microtime(true) - $started, 'termination must be bounded');
        self::assertFalse($worker->isRunning(), 'no worker process may outlive the test');
    }

    /**
     * @param array<string, string|int> $overrides
     *
     * @return array<string, string|int>
     */
    private function task(array $overrides): array
    {
        return array_merge([
            'WP_TESTS_DIR' => '/nonexistent-cpms-fixture',
            'CPMS_RACE_DB_NAME' => 'cpms_unit_failure_path_no_database',
            'CPMS_RACE_DB_USER' => 'cpms_unit_no_user',
            'CPMS_RACE_DB_PASSWORD' => 'cpms-unit-no-secret',
            'CPMS_RACE_DB_HOST' => '127.0.0.1',
            'CPMS_RACE_TABLE_PREFIX' => 'wptests_',
            'CPMS_RACE_CLINIC_ID' => 1,
            'CPMS_RACE_DEFINITION_ID' => 1,
            'CPMS_RACE_MANAGER_USER_ID' => 1,
            'CPMS_RACE_TARGET_USER_ID' => 1,
            'CPMS_RACE_ROLE_KEY' => 'unit_failure_path_key',
            'CPMS_RACE_OPERATION' => 'assign',
            'CPMS_RACE_LOCK_WAIT_TIMEOUT' => 1,
        ], $overrides);
    }
}
